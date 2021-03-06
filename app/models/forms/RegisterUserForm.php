<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
/**
 * RegisterUserForm class.
 * RegisterUserForm is the data structure for keeping user data.
 * It is used by the 'register' action of 'WebController'.
 */
class RegisterUserForm extends CFormModel
{
	public $email;
	public $password;
	public $password_repeat;
	public $first_name;
	public $last_name;
	public $display_name;

	protected $_viaEmail = false;

	/**
	 * Declares the validation rules.
	 */
	public function rules()
	{
		if ( $this->_viaEmail )
		{
			return array(
				// names, password, and email are required
				array( 'email, last_name, first_name, display_name', 'required' ),
				// email has to be a valid email address
				array( 'email', 'email' ),
			);
		}
		else
		{
			return array(
				// names, password, and email are required
				array( 'email, password, password_repeat, last_name, first_name, display_name', 'required' ),
				// password repeat must match password
				array( 'password_repeat', 'required' ),
				array( 'password_repeat', 'compare', 'compareAttribute' => 'password' ),
				// email has to be a valid email address
				array( 'email', 'email' ),
			);
		}
	}

	/**
	 * Declares customized attribute labels.
	 * If not declared here, an attribute would have a label that is
	 * the same as its name with the first letter in upper case.
	 */
	public function attributeLabels()
	{
		return array(
			'email'           => 'Email Address',
			'password'        => 'Desired Password',
			'password_repeat' => 'Verify Password',
			'first_name'      => 'First Name',
			'last_name'       => 'Last Name',
			'display_name'    => 'Display Name',
		);
	}

	/**
	 * @param $via_email
	 *
	 * @return RegisterUserForm
	 */
	public function setViaEmail( $via_email )
	{
		$this->_viaEmail = $via_email;

		return $this;
	}

	/**
	 * @return boolean
	 */
	public function getViaEmail()
	{
		return $this->_viaEmail;
	}

}